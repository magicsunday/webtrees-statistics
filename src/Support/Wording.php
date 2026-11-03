<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Support;

use Fisharebest\Webtrees\I18N;

/**
 * The texts that the module words differently from the webtrees core.
 *
 * Each text carries the name of the module as its context, so that its entry in the catalogue
 * does not replace the core text in the rest of the installation. Every source text is a literal
 * at its call so that the extraction of the catalogue finds it.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class Wording
{
    /**
     * Private constructor, the class only offers static methods.
     */
    private function __construct()
    {
    }

    /**
     * Returns the eyebrow of the card group that counts the media objects.
     */
    public static function media(): string
    {
        return ContextText::resolve(I18N::translateContext('webtrees-statistics', 'Media'), 'Media');
    }

    /**
     * Returns the label of the catch-all bucket for values that no other label covers.
     */
    public static function other(): string
    {
        return ContextText::resolve(I18N::translateContext('webtrees-statistics', 'Other'), 'Other');
    }
}
