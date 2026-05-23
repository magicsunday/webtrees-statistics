# Bundled Webfonts

This module ships two self-hosted typefaces. Both are licensed under
the SIL Open Font License 1.1, which allows free use, modification,
and redistribution.

| Family            | Files                                                                       | Source                                   |
|-------------------|-----------------------------------------------------------------------------|------------------------------------------|
| Instrument Serif  | `InstrumentSerif-Regular-latin.woff2`, `-latin-ext.woff2`, `-Italic-*.woff2` | https://github.com/Instrument/instrument-serif |
| Geist             | `Geist-latin.woff2`, `Geist-latin-ext.woff2` (variable font, 100–900)        | https://github.com/vercel/geist-font     |

Files were exported from the Google Fonts CSS API (latin + latin-ext
subsets only). Cyrillic and Vietnamese subsets are intentionally
omitted to keep the page weight under 120 KB.

The monospace face (`--mono`) falls back to the operating system
(`SF Mono` / `Menlo` / `Monaco` / `Consolas` / system monospace) — no
mono webfont ships with the module.
