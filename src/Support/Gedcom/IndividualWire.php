<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Support\Gedcom;

use Fisharebest\Webtrees\Individual;

/**
 * Flattens a webtrees {@see Individual} to the JSON-encodable `{xref, label,
 * sex, birth, death, url}` wire row a JS/JSON consumer can drive without an
 * Individual proxy. Shared by every report VO that carries live people for the
 * PHTML view but must serialise them away — the marriage-reach chain and its
 * group excerpt both flatten people this exact way, so the strip-name +
 * year-only + url idiom lives here once.
 *
 * Privacy: the label is the record's own `fullName()` (markup stripped,
 * entities decoded), so a non-visible person already reads as "Private" without
 * any caller-side gate. The DERIVED facts are NOT privatised by `fullName()`,
 * though — `sex()` reads the unprivatised record and `url()` is an xref deep-link, so a
 * `!canShow()` (typically living) person keeps a positionally-identifiable node in a
 * graph while its real sex and page link would leak. So for a non-visible person every
 * derived field is withheld (empty `birth`/`death`/`url`, unknown `sex`), exactly the
 * discipline {@see \MagicSunday\Webtrees\Statistic\Aggregator\RecordRowMapper}
 * documents — only `xref` (graph topology, never a label on its own) and the
 * already-privatised `label` survive.
 *
 * A visible record can still keep one fact back, and `sex()` ignores that, so
 * for a visible person the sex is read through the privacy-aware fact path
 * and reads as unknown when its SEX fact is restricted for the viewer. The
 * birth and death years already come through host date accessors that apply
 * the fact-level privacy.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class IndividualWire
{
    /**
     * Prevent instantiation — static-only utility.
     */
    private function __construct()
    {
    }

    /**
     * Flatten one {@see Individual} to its `{xref, label, sex, birth, death,
     * url}` wire row.
     *
     * @param Individual $individual The person to flatten
     *
     * @return array{xref: string, label: string, sex: string, birth: string, death: string, url: string}
     */
    public static function row(Individual $individual): array
    {
        // Keep the node in the graph (topology / counts must not shift by viewer)
        // but withhold every derived fact for a person the viewer cannot see: the
        // name is already privatised by fullName(), the rest is not.
        if (!$individual->canShow()) {
            return [
                'xref'  => $individual->xref(),
                'label' => RecordName::plain($individual->fullName()),
                'sex'   => 'U',
                'birth' => '',
                'death' => '',
                'url'   => '',
            ];
        }

        return [
            'xref'  => $individual->xref(),
            'label' => RecordName::plain($individual->fullName()),
            'sex'   => self::visibleSex($individual),
            'birth' => $individual->getBirthDate()->minimumDate()->format('%Y'),
            'death' => $individual->getDeathDate()->minimumDate()->format('%Y'),
            'url'   => $individual->url(),
        ];
    }

    /**
     * The sex stored in the viewer-visible SEX fact, or the unknown value when
     * that fact is withheld (an explicit `RESN` on the fact, or a tree or
     * individual default restriction) or carries no recognised value. Unlike
     * `Individual::sex()`, which reads the unprivatised record, this goes
     * through `Individual::facts()` and so honors the fact-level privacy.
     *
     * @param Individual $individual The visible person whose sex is read
     *
     * @return string One of `M`, `F`, `X` or `U`
     */
    private static function visibleSex(Individual $individual): string
    {
        $value = $individual->facts(['SEX'])->first()?->value();

        return match ($value) {
            'M', 'F', 'X' => $value,
            default       => 'U',
        };
    }
}
