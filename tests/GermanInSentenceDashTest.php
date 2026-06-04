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

use function dirname;
use function file_get_contents;
use function implode;
use function ltrim;
use function preg_match;
use function preg_split;
use function str_starts_with;
use function strlen;
use function strpos;
use function strrpos;
use function substr;

/**
 * Locks the German copy rule that flowing info-popover / tooltip / caption text
 * never carries an in-sentence em-dash insert (` — `, U+2014 flanked by
 * spaces). Such inserts must be a period plus a new sentence, a comma, or a
 * colon. The single allowed em-dash is the leading stat-readout separator,
 * where the dash sits between two sprintf placeholders (`%1$s%% — %2$s …`).
 *
 * Range notation (`1900–1909`) and label qualifiers (`Häufige Vornamen –
 * weiblich`) use the en-dash (U+2013), so they are not matched at all.
 *
 * The German `msgstr` is the binding copy; English msgid sources keep idiomatic
 * em-dashes in long prose, so only `resources/lang/de/messages.po` is policed.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversNothing]
final class GermanInSentenceDashTest extends TestCase
{
    /**
     * Every German `msgstr` MUST be free of in-sentence em-dash inserts. The
     * only exception is the placeholder-flanked stat-readout separator. Surfaces
     * each offending msgid so a regression fails with a concrete list, not a
     * vague "the copy drifted again".
     */
    #[Test]
    public function germanCatalogueHasNoInSentenceEmDash(): void
    {
        $poFile = dirname(__DIR__) . '/resources/lang/de/messages.po';
        $po     = file_get_contents($poFile);

        self::assertNotFalse($po, 'de/messages.po must be readable');

        $offenders = $this->collectOffendingTranslations($po);

        self::assertSame(
            [],
            $offenders,
            "German msgstr must not use an in-sentence em-dash insert (' — '); use a period, comma or colon instead. "
            . 'Offending source strings: ' . implode(' | ', $offenders),
        );
    }

    /**
     * Parses the PO into msgid/msgstr pairs, skips obsolete (`#~`) entries, and
     * returns the msgid of every entry whose translation carries a disallowed
     * in-sentence em-dash.
     *
     * @param string $po Full content of `resources/lang/de/messages.po`
     *
     * @return list<string>
     */
    private function collectOffendingTranslations(string $po): array
    {
        $offenders  = [];
        $currentId  = '';
        $currentStr = '';
        $inId       = false;
        $inStr      = false;

        $lines = preg_split('/\R/', $po);

        if ($lines === false) {
            return $offenders;
        }

        foreach ($lines as $line) {
            // A blank line or the next msgid closes the open entry; record it
            // before the accumulators reset for the following block.
            if (($line === '') || str_starts_with($line, 'msgid ')) {
                if ($this->hasInSentenceEmDash($currentStr)) {
                    $offenders[] = $currentId;
                }

                $currentId  = '';
                $currentStr = '';
                $inId       = false;
                $inStr      = false;
            }

            if ($line === '') {
                continue;
            }

            // Obsolete and other comment lines never carry shippable copy.
            if (str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, 'msgid ')) {
                $inId      = true;
                $currentId = $this->quotedValue(substr($line, strlen('msgid ')));

                continue;
            }

            if (str_starts_with($line, 'msgid_plural ')) {
                $inId = true;
                $currentId .= $this->quotedValue(substr($line, strlen('msgid_plural ')));

                continue;
            }

            if (str_starts_with($line, 'msgstr')) {
                $inId  = false;
                $inStr = true;
                $currentStr .= $this->quotedValue(substr($line, (int) strpos($line, '"')));

                continue;
            }

            // Continuation line: a bare quoted string belonging to the open block.
            if (str_starts_with($line, '"')) {
                if ($inStr) {
                    $currentStr .= $this->quotedValue($line);
                } elseif ($inId) {
                    $currentId .= $this->quotedValue($line);
                }
            }
        }

        if ($this->hasInSentenceEmDash($currentStr)) {
            $offenders[] = $currentId;
        }

        return $offenders;
    }

    /**
     * Returns TRUE when the translation contains a spaced em-dash that is not the
     * placeholder-flanked stat-readout separator.
     *
     * @param string $translation Joined msgstr value
     */
    private function hasInSentenceEmDash(string $translation): bool
    {
        // A spaced em-dash (U+2014) is a forbidden in-sentence insert unless it
        // is the `…%% — %…` stat-readout separator, i.e. flanked by sprintf
        // placeholders on both sides.
        return preg_match('/(?<!%) — | — (?!%)/u', $translation) === 1;
    }

    /**
     * Strips the surrounding double quotes from a single PO string token,
     * returning the raw inner text (escape sequences are irrelevant to the
     * em-dash check, so they are left untouched).
     *
     * @param string $token A `"…"` token, optionally with trailing whitespace
     */
    private function quotedValue(string $token): string
    {
        $token = ltrim($token);

        if (!str_starts_with($token, '"')) {
            return '';
        }

        // Each PO line is one complete quoted token; internal quotes are escaped
        // as \", so the closing quote is the last one on the line.
        $end = strrpos($token, '"');

        if (($end === false) || ($end === 0)) {
            return '';
        }

        return substr($token, 1, $end - 1);
    }
}
