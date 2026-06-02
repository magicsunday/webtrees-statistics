<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Support\Locale;

use Fisharebest\Webtrees\I18N;
use LogicException;

use function array_intersect;
use function count;

/**
 * A curated catalogue of historical events with a documented excess-mortality
 * signal, used to annotate the mortality-anomaly years on the life-span tab.
 *
 * Each event carries the ISO-3166-1 alpha-2 countries it applies to (a war or
 * pandemic spans several, so the event is modelled once with a country set), an
 * inclusive year span, and a stable key. An anomaly year is annotated when the
 * year falls in an event's span and the event's country set intersects the
 * countries the year's death places resolve to.
 *
 * Labels are bare event names in citation form; the "coincides with" framing —
 * a temporal coincidence, never a cause of death, since the data shows the
 * spike, not its reason — is added once by the view. Coverage is intentionally
 * conservative — only broadly-documented,
 * clearly-dated events for the most common installation countries — and is
 * sourced from webtrees' own historical-events modules and equivalent curated
 * datasets rather than invented here.
 *
 * The matching ({@see keysFor()}) is pure and free of any framework dependency
 * so it can be unit-tested in isolation; the localised label ({@see labelFor()})
 * is resolved separately so the source strings stay extractable by xgettext.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class HistoricalEventCatalog
{
    /**
     * Maximum number of events attached to a single anomaly year, so a year
     * that coincides with several events (e.g. 1918: a world war and the
     * influenza pandemic) stays readable.
     */
    private const int MAX_EVENTS = 2;

    /**
     * The most common webtrees installation countries (ISO-3166-1 alpha-2),
     * reused as the country set of the genuinely global events (pandemics).
     *
     * @var list<string>
     */
    private const array ALL_COUNTRIES = [
        'DE', 'US', 'FR', 'GB', 'NL', 'PL', 'CH', 'CA', 'DK', 'AU',
        'RU', 'IT', 'CN', 'CZ', 'AT', 'BE', 'NO', 'SE', 'ES', 'IE',
    ];

    /**
     * The event catalogue, ordered by start year. Each entry is the structural
     * data only — the localised label lives in {@see labelFor()} keyed by `key` —
     * so this constant and {@see keysFor()} carry no framework dependency.
     *
     * @var list<array{key: string, countries: list<string>, from: int, to: int}>
     */
    private const array EVENTS = [
        ['key' => 'black-death', 'countries' => ['DE', 'FR', 'GB', 'NL', 'BE', 'IT', 'ES', 'IE', 'CH', 'AT', 'CZ', 'DK', 'SE', 'NO', 'PL'], 'from' => 1348, 'to' => 1350],
        ['key' => 'italian-wars', 'countries' => ['IT'], 'from' => 1500, 'to' => 1559],
        ['key' => 'french-wars-of-religion', 'countries' => ['FR'], 'from' => 1562, 'to' => 1598],
        ['key' => 'eighty-years-war', 'countries' => ['NL', 'BE'], 'from' => 1568, 'to' => 1648],
        ['key' => 'nine-years-war', 'countries' => ['IE'], 'from' => 1594, 'to' => 1603],
        ['key' => 'time-of-troubles', 'countries' => ['RU'], 'from' => 1598, 'to' => 1613],
        ['key' => 'thirty-years-war', 'countries' => ['DE', 'CZ', 'AT'], 'from' => 1618, 'to' => 1648],
        ['key' => 'fall-of-ming', 'countries' => ['CN'], 'from' => 1620, 'to' => 1644],
        ['key' => 'italian-plague-1629', 'countries' => ['IT'], 'from' => 1629, 'to' => 1631],
        ['key' => 'wars-of-three-kingdoms', 'countries' => ['GB', 'IE'], 'from' => 1639, 'to' => 1651],
        ['key' => 'french-famine-1650', 'countries' => ['FR'], 'from' => 1650, 'to' => 1652],
        ['key' => 'swedish-deluge', 'countries' => ['PL'], 'from' => 1655, 'to' => 1660],
        ['key' => 'dano-swedish-wars', 'countries' => ['DK'], 'from' => 1657, 'to' => 1660],
        ['key' => 'great-plague-london', 'countries' => ['GB'], 'from' => 1665, 'to' => 1666],
        ['key' => 'great-plague-vienna', 'countries' => ['AT'], 'from' => 1679, 'to' => 1679],
        ['key' => 'french-famine-1693', 'countries' => ['FR'], 'from' => 1693, 'to' => 1694],
        ['key' => 'great-northern-war', 'countries' => ['PL', 'RU', 'SE', 'DK'], 'from' => 1700, 'to' => 1721],
        ['key' => 'war-of-spanish-succession', 'countries' => ['ES', 'BE'], 'from' => 1701, 'to' => 1714],
        ['key' => 'great-frost-1709', 'countries' => ['DE', 'FR', 'GB', 'NL', 'IT', 'CH', 'BE', 'AT', 'CZ', 'DK', 'PL', 'ES', 'IE', 'SE', 'NO'], 'from' => 1709, 'to' => 1709],
        ['key' => 'seven-years-war', 'countries' => ['DE', 'AT', 'CZ'], 'from' => 1756, 'to' => 1763],
        ['key' => 'habsburg-famine-1770', 'countries' => ['CZ', 'AT'], 'from' => 1770, 'to' => 1772],
        ['key' => 'napoleonic-wars', 'countries' => ['FR', 'DE', 'AT', 'RU', 'IT', 'ES', 'NL', 'BE', 'PL'], 'from' => 1803, 'to' => 1815],
        ['key' => 'norwegian-famine-1812', 'countries' => ['NO'], 'from' => 1812, 'to' => 1814],
        ['key' => 'year-without-summer', 'countries' => ['DE', 'FR', 'GB', 'NL', 'CH', 'AT', 'BE', 'IT', 'IE', 'DK', 'SE', 'NO', 'US', 'CA'], 'from' => 1816, 'to' => 1817],
        ['key' => 'cholera-1831', 'countries' => ['DE'], 'from' => 1831, 'to' => 1832],
        ['key' => 'great-famine-ireland', 'countries' => ['IE', 'GB'], 'from' => 1845, 'to' => 1852],
        ['key' => 'taiping-rebellion', 'countries' => ['CN'], 'from' => 1850, 'to' => 1864],
        ['key' => 'crimean-war', 'countries' => ['GB', 'FR', 'RU', 'IT'], 'from' => 1853, 'to' => 1856],
        ['key' => 'us-civil-war', 'countries' => ['US'], 'from' => 1861, 'to' => 1865],
        ['key' => 'swedish-famine-1867', 'countries' => ['SE'], 'from' => 1867, 'to' => 1869],
        ['key' => 'franco-prussian-war', 'countries' => ['DE', 'FR'], 'from' => 1870, 'to' => 1871],
        ['key' => 'smallpox-1870', 'countries' => ['DE'], 'from' => 1870, 'to' => 1873],
        ['key' => 'russian-flu-1889', 'countries' => self::ALL_COUNTRIES, 'from' => 1889, 'to' => 1890],
        ['key' => 'russian-famine-1891', 'countries' => ['RU'], 'from' => 1891, 'to' => 1892],
        ['key' => 'cholera-hamburg-1892', 'countries' => ['DE'], 'from' => 1892, 'to' => 1892],
        ['key' => 'first-world-war', 'countries' => ['DE', 'FR', 'GB', 'BE', 'IT', 'RU', 'AU', 'CA', 'AT', 'CZ', 'US'], 'from' => 1914, 'to' => 1918],
        ['key' => 'russian-revolution-civil-war', 'countries' => ['RU'], 'from' => 1917, 'to' => 1922],
        ['key' => 'influenza-pandemic', 'countries' => self::ALL_COUNTRIES, 'from' => 1918, 'to' => 1920],
        ['key' => 'polish-soviet-war', 'countries' => ['PL'], 'from' => 1919, 'to' => 1921],
        ['key' => 'russian-famine-1921', 'countries' => ['RU'], 'from' => 1921, 'to' => 1922],
        ['key' => 'soviet-famine-1930', 'countries' => ['RU'], 'from' => 1930, 'to' => 1933],
        ['key' => 'great-depression', 'countries' => ['US'], 'from' => 1930, 'to' => 1939],
        ['key' => 'spanish-civil-war', 'countries' => ['ES'], 'from' => 1936, 'to' => 1942],
        ['key' => 'second-sino-japanese-war', 'countries' => ['CN'], 'from' => 1937, 'to' => 1945],
        ['key' => 'second-world-war', 'countries' => ['DE', 'FR', 'GB', 'NL', 'PL', 'BE', 'IT', 'RU', 'CA', 'AU', 'AT', 'CZ', 'NO', 'US', 'DK'], 'from' => 1939, 'to' => 1945],
        ['key' => 'dutch-hongerwinter', 'countries' => ['NL'], 'from' => 1944, 'to' => 1945],
        ['key' => 'chinese-civil-war', 'countries' => ['CN'], 'from' => 1945, 'to' => 1949],
        ['key' => 'great-smog-london', 'countries' => ['GB'], 'from' => 1952, 'to' => 1952],
        ['key' => 'great-chinese-famine', 'countries' => ['CN'], 'from' => 1959, 'to' => 1961],
        ['key' => 'hiv-aids-us', 'countries' => ['US'], 'from' => 1981, 'to' => 1996],
        ['key' => 'french-heatwave-2003', 'countries' => ['FR'], 'from' => 2003, 'to' => 2003],
        ['key' => 'covid-19', 'countries' => self::ALL_COUNTRIES, 'from' => 2020, 'to' => 2023],
    ];

    /**
     * Static-only utility; not constructible.
     */
    private function __construct()
    {
    }

    /**
     * The keys of the catalogue events that a given anomaly year coincides with:
     * the year falls inside the event's inclusive span and the event's country
     * set intersects the supplied death-place countries. Capped at
     * {@see MAX_EVENTS}, in catalogue (chronological) order. Pure — no framework
     * dependency — so the matching stays unit-testable.
     *
     * @param int          $year      The anomaly year
     * @param list<string> $countries ISO-3166-1 alpha-2 death-place countries that reached the qualifying threshold for the year
     *
     * @return list<string> Matching event keys, at most {@see MAX_EVENTS}
     */
    public static function keysFor(int $year, array $countries): array
    {
        if ($countries === []) {
            return [];
        }

        $keys = [];

        foreach (self::EVENTS as $event) {
            if ($year < $event['from']) {
                continue;
            }

            if ($year > $event['to']) {
                continue;
            }

            if (array_intersect($event['countries'], $countries) === []) {
                continue;
            }

            $keys[] = $event['key'];

            if (count($keys) >= self::MAX_EVENTS) {
                break;
            }
        }

        return $keys;
    }

    /**
     * The localised event labels an anomaly year coincides with, or an empty
     * list when it coincides with no catalogued event. Bare event names in
     * citation form (no leading "Coincides with") so the view can join several
     * under one "Coincides with: …" lead-in rather than repeating the phrase.
     *
     * @param int          $year      The anomaly year
     * @param list<string> $countries ISO-3166-1 alpha-2 death-place countries that reached the qualifying threshold for the year
     *
     * @return list<string>
     */
    public static function labelsFor(int $year, array $countries): array
    {
        $labels = [];

        foreach (self::keysFor($year, $countries) as $key) {
            $labels[] = self::labelFor($key);
        }

        return $labels;
    }

    /**
     * The localised name of a single event in citation form. The source strings
     * are plain `I18N::translate()` literals so xgettext can extract them. The
     * "coincides with" framing — a temporal coincidence, never a stated cause of
     * death — is added once by the view layer.
     *
     * @param string $key A key from {@see EVENTS}
     */
    private static function labelFor(string $key): string
    {
        return match ($key) {
            'black-death'                  => I18N::translate('Black Death (1348–1350)'),
            'italian-wars'                 => I18N::translate('Italian Wars (1500–1559)'),
            'french-wars-of-religion'      => I18N::translate('French Wars of Religion (1562–1598)'),
            'eighty-years-war'             => I18N::translate('Eighty Years’ War (1568–1648)'),
            'nine-years-war'               => I18N::translate('Nine Years’ War (1594–1603)'),
            'time-of-troubles'             => I18N::translate('Time of Troubles (1598–1613)'),
            'thirty-years-war'             => I18N::translate('Thirty Years’ War (1618–1648)'),
            'fall-of-ming'                 => I18N::translate('Fall of the Ming dynasty (1620–1644)'),
            'italian-plague-1629'          => I18N::translate('Italian plague of 1629–1631'),
            'wars-of-three-kingdoms'       => I18N::translate('Wars of the Three Kingdoms (1639–1651)'),
            'french-famine-1650'           => I18N::translate('Famine in eastern France (1650–1652)'),
            'swedish-deluge'               => I18N::translate('Swedish Deluge (1655–1660)'),
            'dano-swedish-wars'            => I18N::translate('Dano-Swedish Wars (1657–1660)'),
            'great-plague-london'          => I18N::translate('Great Plague of London (1665–1666)'),
            'great-plague-vienna'          => I18N::translate('Great Plague of Vienna (1679)'),
            'french-famine-1693'           => I18N::translate('Famine of 1693–1694'),
            'great-northern-war'           => I18N::translate('Great Northern War (1700–1721)'),
            'war-of-spanish-succession'    => I18N::translate('War of the Spanish Succession (1701–1714)'),
            'great-frost-1709'             => I18N::translate('Great Frost of 1709'),
            'seven-years-war'              => I18N::translate('Seven Years’ War (1756–1763)'),
            'habsburg-famine-1770'         => I18N::translate('Habsburg famine of 1770–1772'),
            'napoleonic-wars'              => I18N::translate('Napoleonic Wars (1803–1815)'),
            'norwegian-famine-1812'        => I18N::translate('Norwegian famine of 1812–1814'),
            'year-without-summer'          => I18N::translate('“Year Without a Summer” (1816–1817)'),
            'cholera-1831'                 => I18N::translate('Cholera pandemic of 1831–1832'),
            'great-famine-ireland'         => I18N::translate('Great Famine (1845–1852)'),
            'taiping-rebellion'            => I18N::translate('Taiping Rebellion (1850–1864)'),
            'crimean-war'                  => I18N::translate('Crimean War (1853–1856)'),
            'us-civil-war'                 => I18N::translate('American Civil War (1861–1865)'),
            'swedish-famine-1867'          => I18N::translate('Swedish famine of 1867–1869'),
            'franco-prussian-war'          => I18N::translate('Franco-Prussian War (1870–1871)'),
            'smallpox-1870'                => I18N::translate('Smallpox epidemic of 1870–1873'),
            'russian-flu-1889'             => I18N::translate('1889–1890 influenza pandemic'),
            'russian-famine-1891'          => I18N::translate('Russian famine of 1891–1892'),
            'cholera-hamburg-1892'         => I18N::translate('Cholera outbreak of 1892'),
            'first-world-war'              => I18N::translate('First World War (1914–1918)'),
            'russian-revolution-civil-war' => I18N::translate('Russian Revolution and Civil War (1917–1922)'),
            'influenza-pandemic'           => I18N::translate('1918–1920 influenza pandemic'),
            'polish-soviet-war'            => I18N::translate('Polish-Soviet War (1919–1921)'),
            'russian-famine-1921'          => I18N::translate('Russian famine of 1921–1922'),
            'soviet-famine-1930'           => I18N::translate('Soviet famine of 1930–1933'),
            'great-depression'             => I18N::translate('Great Depression and Dust Bowl (1930–1939)'),
            'spanish-civil-war'            => I18N::translate('Spanish Civil War and post-war years (1936–1942)'),
            'second-sino-japanese-war'     => I18N::translate('Second Sino-Japanese War (1937–1945)'),
            'second-world-war'             => I18N::translate('Second World War (1939–1945)'),
            'dutch-hongerwinter'           => I18N::translate('Dutch famine (1944–1945)'),
            'chinese-civil-war'            => I18N::translate('Chinese Civil War (1945–1949)'),
            'great-smog-london'            => I18N::translate('Great Smog of London (1952)'),
            'great-chinese-famine'         => I18N::translate('Great Chinese Famine (1959–1961)'),
            'hiv-aids-us'                  => I18N::translate('HIV/AIDS epidemic (1981–1996)'),
            'french-heatwave-2003'         => I18N::translate('European heatwave of 2003'),
            'covid-19'                     => I18N::translate('COVID-19 pandemic (2020–2023)'),
            default                        => throw new LogicException('Unknown historical event key: ' . $key),
        };
    }
}
