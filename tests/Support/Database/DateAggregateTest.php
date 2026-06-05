<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Support\Database;

use Illuminate\Database\Capsule\Manager as Capsule;
use MagicSunday\Webtrees\Statistic\Support\Database\DateAggregate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that {@see DateAggregate::min()} and {@see DateAggregate::max()}
 * emit the correctly prefix-quoted `MIN(prefix_alias.col) AS out` /
 * `MAX(prefix_alias.col) AS out` fragments the six cohort-repository consumers
 * depend on. The helper concatenates the table prefix from the live connection,
 * so the test spins up an in-memory SQLite Capsule with a fixed `wt_` prefix
 * and inspects the resulting `Expression::getValue()` output directly — locking
 * the rendered SQL string at the helper boundary so a future prefix-quoting
 * change cannot silently drift across every call site.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(DateAggregate::class)]
final class DateAggregateTest extends TestCase
{
    private Capsule $capsule;

    /**
     * Spin up a fresh in-memory SQLite via Eloquent's Capsule with the standard
     * webtrees `wt_` table-prefix so the produced Expression strings carry the
     * same prefix the real repository code would see at runtime.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->capsule = new Capsule();
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'wt_']);
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();
    }

    /**
     * `DateAggregate::min()` renders `MIN(prefix_alias.column) AS as`. Used by
     * ageGapDistribution, marriageDurationPairs, ageAtDivorceDistribution and
     * divorceRateByMarriageCohort to collapse ranged-date double rows onto the
     * lower-bound anchor.
     */
    #[Test]
    public function minRendersPrefixedMinExpression(): void
    {
        $expression = DateAggregate::min('birth', 'd_julianday1', 'birth_jd');

        self::assertSame('MIN(wt_birth.d_julianday1) AS birth_jd', $expression->getValue($this->capsule->getConnection()->getQueryGrammar()));
    }

    /**
     * `DateAggregate::max()` renders `MAX(prefix_alias.column) AS as`. Used by
     * widowhoodYearsDistribution to pick the upper-bound DEAT julian day in
     * line with webtrees core's maximum-possible-lifespan convention.
     */
    #[Test]
    public function maxRendersPrefixedMaxExpression(): void
    {
        $expression = DateAggregate::max('husb_d', 'd_julianday2', 'husb_jd');

        self::assertSame('MAX(wt_husb_d.d_julianday2) AS husb_jd', $expression->getValue($this->capsule->getConnection()->getQueryGrammar()));
    }

    /**
     * Both helpers honour an arbitrary alias / column pair so a future cohort
     * method picking different anchors (e.g. a `divr` alias with the `d_year`
     * column) gets the same predictable rendering. Locks the column / alias
     * slots against accidental hard-coding.
     */
    #[Test]
    public function minAndMaxHonourCustomAliasAndColumn(): void
    {
        $minYear = DateAggregate::min('divr', 'd_year', 'div_year');
        $maxJd   = DateAggregate::max('wife_d', 'd_julianday2', 'wife_jd');

        $grammar = $this->capsule->getConnection()->getQueryGrammar();

        self::assertSame('MIN(wt_divr.d_year) AS div_year', $minYear->getValue($grammar));
        self::assertSame('MAX(wt_wife_d.d_julianday2) AS wife_jd', $maxJd->getValue($grammar));
    }
}
