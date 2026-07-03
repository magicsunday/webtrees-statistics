<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use Fisharebest\Webtrees\Site;
use MagicSunday\Webtrees\Statistic\Normalization\Support\ContentLanguage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Verifies that the occupation content-language hint is resolved from the
 * SITE-level `LANGUAGE` preference — the only language webtrees actually
 * configures (there is no per-tree language). An empty preference resolves to
 * null so a provider that gates on a concrete language is simply not steered.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(ContentLanguage::class)]
final class ContentLanguageTest extends AbstractIntegrationTestCase
{
    /**
     * The configured site default language becomes the content-language hint.
     */
    #[Test]
    public function tagReturnsTheConfiguredSiteLanguage(): void
    {
        $previous = Site::getPreference('LANGUAGE');

        try {
            Site::setPreference('LANGUAGE', 'de');

            self::assertSame('de', ContentLanguage::tag());
        } finally {
            Site::setPreference('LANGUAGE', $previous);
        }
    }

    /**
     * An unset (empty) site language resolves to null rather than an empty
     * string, so a language-gated provider receives "no hint" uniformly.
     */
    #[Test]
    public function tagReturnsNullWhenTheSiteLanguageIsEmpty(): void
    {
        $previous = Site::getPreference('LANGUAGE');

        try {
            Site::setPreference('LANGUAGE', '');

            self::assertNull(ContentLanguage::tag());
        } finally {
            Site::setPreference('LANGUAGE', $previous);
        }
    }
}
