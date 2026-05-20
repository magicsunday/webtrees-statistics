# Changelog

All notable changes to `magicsunday/webtrees-statistics` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/) and the
project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- Phase 1 baseline: the module now renders on webtrees 2.2.
- Tab-based navigation with four implemented tabs (Overview, Places, Births, Deaths) and five `Coming soon` placeholders for Relationships, Age, Weddings, Divorces, Children.
- `MaritalBucket` backed enum (`current` / `divorced` / `widowed` / `single`).
- `FamilyRepository::classifyLivingIndividuals()` — Census-aligned marital classifier that buckets every living individual exactly once and whose four counts sum to `StatisticsData::countIndividualsLiving()`.
- `NameRepository` — distinct primary-name counts (`n_num = 0`) for surnames and given names so totals stay consistent with the Top-N name lists.
- `EventRepository::getBirthsByZodiacSign()` — twelve-bucket grouping the core accessor does not expose.
- Theme-aware stylesheet `resources/css/statistics.css` with `[data-bs-theme="dark"]` palette override.
- Unit coverage for the bucket enum and the classifier precedence (28 tests, 51 assertions).
- Project AGENTS.md documenting architecture, build pipeline, and bucket precedence.

### Changed
- Aggregator service `Statistic` is now `final readonly` and receives `StatisticsData` + the three repositories via constructor injection through the webtrees DI container.
- Marriage and divorce tag sets used by the classifier intentionally drop `_NMR` (not married) from `Gedcom::MARRIAGE_EVENTS` and `_SEPR` (separated) from `Gedcom::DIVORCE_EVENTS` to match Census semantics; otherwise both tags would invert the bucket they trigger.
- PHPStan baseline is no longer needed; the project now runs at `level: max` without ignores.
- Hardcoded German card titles replaced with `I18N::translate()` and `I18N::plural()` calls.

### Removed
- `Statistics\Repository\*` and `Statistics\Google\*` integration paths (gone from webtrees 2.2; their behaviour is consolidated into `StatisticsData`).
- Inline `<style>` blocks in templates; styling is centralised in the new stylesheet.

### Known limitations
- Country grouping for births and deaths returns an empty list until webtrees core exposes a public accessor for `countIndividualEventsByCountry`. The `Places` tab falls back to its empty-state rendering until then. Tracked in [#7](https://github.com/magicsunday/webtrees-statistics/issues/7).
- Module-level translations are not yet shipped; UI strings fall back to webtrees core translations.
