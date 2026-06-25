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
use Normalizer;
use Transliterator;

use function ksort;
use function mb_strtolower;
use function normalizer_normalize;
use function preg_match;
use function preg_replace;
use function preg_split;
use function trim;

use const PREG_SPLIT_NO_EMPTY;
use const SORT_STRING;

/**
 * Pure helper that owns the single source of truth for "Level-1" given-name
 * normalisation, shared by every card that aggregates given names. It splits a
 * `n_givn` value into countable display tokens and derives the folded grouping
 * key that collapses spelling variants of the same name.
 *
 * The two operations are deliberately separate: {@see tokens()} keeps the
 * readable display form (so a ranked list or chart label shows "José", not
 * "jose"), while {@see foldKey()} produces the case- and diacritics-folded key
 * the caller groups and counts on. A consumer therefore groups by `foldKey()`
 * and renders the most frequent raw token of each group as the label.
 *
 * The split + particle/initial filter matches webtrees core's
 * `StatisticsData::commonGivenNames()` tokenisation (single capital initial, or
 * one-to-three lowercase particle). The fold adds Unicode NFC, diacritics and
 * special-Latin-letter folding (via ICU `Latin-ASCII`) and case folding, so
 * `José`/`Jose`, `Sofía`/`Sofia` and `Łukasz`/`Lukasz` count as one name.
 *
 * Folding limits (deliberate, accepted): `Latin-ASCII` is a transliteration,
 * not a locale-aware orthography map, so it strips diacritics rather than
 * expanding them (`ä` → `a`, never `ae`; `ß` → `ss`) and would merge two names
 * that differ only by a foldable letter (`Männ`/`Mann`). It leaves non-Latin
 * scripts (Cyrillic, Greek, CJK) untouched, so accent variants fold for Latin
 * names but not for those scripts. This is the right trade for an international
 * given-name frequency chart — collapsing spelling drift of one name matters
 * more than the rare distinct-name collision.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class GivenNameNormalizer
{
    /**
     * Strips given-name particles and initials: a single capital letter (an
     * initial) or one-to-three lowercase letters (a particle such as "von" /
     * "de" / "van"). Mirrors webtrees core's `commonGivenNames` tokenisation.
     */
    private const string PARTICLE_REGEX = '/^([A-Z]|[a-z]{1,3})$/';

    /**
     * Strips bracketed segments — `(…)` / `[…]` / `{…}` — and the
     * backtick / double-quote characters used to delimit epithets, regnal
     * qualifiers, alternate spellings and titles inside a messy `n_givn` value
     * (issue #156). Replaced with a space so the surrounding tokens never merge.
     * Unbalanced brackets leave a stray bracket character behind, which the
     * residual single-character strip removes. The straight apostrophe is
     * deliberately NOT stripped: it is an intra-word letter in real
     * anthroponyms (`O'Brien`, `Sa'id`), and removing it would split the name
     * and drop the leading initial as a particle.
     */
    private const string NOISE_SEGMENT_REGEX = '/\([^)]*\)|\[[^\]]*\]|\{[^}]*\}|[(){}\[\]`"]/u';

    /**
     * Drops a multi-letter Roman numeral (a regnal number such as "II" / "VIII"
     * / "XIV"); a single Roman letter is already removed as an initial by
     * {@see PARTICLE_REGEX}. This is a deliberately loose character match, not a
     * Roman-numeral validator: it drops ANY all-caps token built solely from
     * the seven Roman letters. The case requirement keeps every mixed-case name
     * safe (`Liv`, `Mimi`, `Vivi` survive); only an all-caps GEDCOM carrying a
     * Roman-letter-only given name (`LIV`, `MIMI`) loses it — accepted as the
     * documented residual rather than risking a real regnal number slipping
     * through a stricter pattern.
     */
    private const string ROMAN_NUMERAL_REGEX = '/^[IVXLCDM]{2,}$/';

    /**
     * Drops an English ordinal fragment ("4th" / "1st" / "2nd" / "3rd") that
     * leaks from a regnal descriptor like "(4th King)".
     */
    private const string ORDINAL_REGEX = '/^\d+(st|nd|rd|th)$/i';

    /**
     * ICU transliterator chain folding accents, special Latin letters and case
     * into a plain lowercase ASCII grouping key (`Łukasz` → `lukasz`).
     */
    private const string FOLD_TRANSFORM = 'Latin-ASCII; Lower()';

    /**
     * Prevent instantiation — static-only utility.
     */
    private function __construct()
    {
    }

    /**
     * Split a `n_givn` value into NFC-normalised display tokens, dropping
     * initials and short particles. The unknown-name placeholder
     * ({@see Individual::PRAENOMEN_NESCIO}) and empty input collapse to an empty
     * list so they neither dilute a cohort nor surface as a name. Slashes and
     * other GEDCOM markers do not appear in `n_givn` (they live on `n_surn` /
     * `n_full`), but messy GIVN fields from royal / legendary lineages pack
     * regnal numbers, parenthetical titles / epithets / variants and
     * quote-delimited bynames alongside the bare name. Bracketed segments and
     * quote / backtick delimiters are stripped before the whitespace split, and
     * the per-token filter additionally drops multi-letter Roman numerals and
     * ordinals so none of that noise ranks as a given name (issue #156). A
     * legitimate multi-token name (`Anna Maria`) is preserved; a small residual
     * (an all-caps place / surname after a particle, or — only on an all-caps
     * GEDCOM that also carries a Roman-letter-only given name such as `LIV` —
     * that name) is accepted as out of reach of a deterministic, locale-free
     * fix.
     *
     * @param string $givn The raw `n_givn` column value
     *
     * @return list<string> The display tokens in source order (no case/diacritics fold)
     */
    public static function tokens(string $givn): array
    {
        $trimmed = trim($givn);

        if (($trimmed === '') || ($trimmed === Individual::PRAENOMEN_NESCIO)) {
            return [];
        }

        $normalisedForm = normalizer_normalize($trimmed, Normalizer::FORM_C);
        $normalised     = ($normalisedForm !== false) ? $normalisedForm : $trimmed;

        // Strip bracketed segments and quote/backtick delimiters so the regnal
        // numbers, titles and alternate spellings packed into a messy GIVN
        // value do not survive the split as bogus names (issue #156). A failed
        // replace (invalid UTF-8) leaves the value untouched for the soft byte
        // split below to handle.
        $stripped = preg_replace(self::NOISE_SEGMENT_REGEX, ' ', $normalised);

        if ($stripped !== null) {
            $normalised = $stripped;
        }

        $rawTokens = preg_split('/\s+/u', $normalised, -1, PREG_SPLIT_NO_EMPTY);

        if ($rawTokens === false) {
            // Invalid UTF-8 makes the /u split fail; fall back to a byte-wise
            // split so a corrupt name still tokenises (foldKey() then fails soft
            // on it) rather than being silently dropped.
            $rawTokens = preg_split('/\s+/', $normalised, -1, PREG_SPLIT_NO_EMPTY);
        }

        if ($rawTokens === false) {
            return [];
        }

        $tokens = [];

        foreach ($rawTokens as $token) {
            if (preg_match(self::PARTICLE_REGEX, $token) === 1) {
                continue;
            }

            if (preg_match(self::ROMAN_NUMERAL_REGEX, $token) === 1) {
                continue;
            }

            if (preg_match(self::ORDINAL_REGEX, $token) === 1) {
                continue;
            }

            $tokens[] = $token;
        }

        return $tokens;
    }

    /**
     * Derive the case- and diacritics-folded grouping key for a single display
     * token, so spelling variants of the same name share one key. Falls back to
     * a plain lowercase mapping if the ICU transform is unavailable for the
     * input.
     *
     * @param string $token A display token as returned by {@see tokens()}
     *
     * @return string The folded lowercase ASCII grouping key
     */
    public static function foldKey(string $token): string
    {
        $normalisedForm = normalizer_normalize($token, Normalizer::FORM_C);
        $normalised     = ($normalisedForm !== false) ? $normalisedForm : $token;

        // Build the ICU transliterator once and reuse it; the string-id form of
        // transliterator_transliterate() re-resolves the rule set on every call,
        // and this runs per token across the whole tree. A separate resolved
        // flag means a null create() (ICU unavailable) is attempted only once,
        // not re-tried on every token.
        static $transliterator = null;
        static $resolved       = false;

        if (!$resolved) {
            $transliterator = Transliterator::create(self::FOLD_TRANSFORM);
            $resolved       = true;
        }

        if ($transliterator instanceof Transliterator) {
            $folded = $transliterator->transliterate($normalised);

            if ($folded !== false) {
                return $folded;
            }
        }

        return mb_strtolower($normalised, 'UTF-8');
    }

    /**
     * Resolve a folded given-name group to its display label: the most frequent
     * raw spelling, breaking ties alphabetically so the choice is deterministic
     * across database engines. The caller accumulates each fold key's raw
     * spellings (keyed by {@see foldKey()}) and passes the per-spelling counts.
     *
     * @param array<array-key, int> $rawCounts Raw spelling => occurrences within the group (a
     *                                         digit-only spelling arrives as an int array key)
     *
     * @return string The dominant raw spelling
     */
    public static function dominantForm(array $rawCounts): string
    {
        // Compare keys as strings (a digit-only spelling is an int array key)
        // so the alphabetical tie-break is deterministic across PHP versions.
        ksort($rawCounts, SORT_STRING);

        $label = '';
        $best  = -1;

        foreach ($rawCounts as $raw => $count) {
            if ($count > $best) {
                $best = $count;

                // A digit-only spelling arrives as an int array key; cast so the
                // declared string return type holds under strict_types.
                $label = (string) $raw;
            }
        }

        return $label;
    }
}
